<?php
/**
 * The cache adapters against vendor stubs.
 *
 * Proves dispatch and mapping only: which vendor entry point each adapter calls, with which
 * arguments, which directory it looks in, and which result it builds from what is on disk. It does
 * not prove that a vendor behaves as its stub does. tests/acceptance/siteops/cache.sh does that on
 * a disposable site with the real plugin.
 *
 * Every cache file is placed at the literal path the vendor uses, written out in the test, never
 * at a path computed by the adapter under test.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

// phpcs:disable WordPress.WP.AlternativeFunctions -- This test creates, renames and deletes real cache files in temp directories, as the cache plugins do.

namespace AAFM\Tests;

use Aafm_Siteops_Vendor_Stub as Stub;

final class SiteCacheAdaptersTest extends TestCase {

	/** A non-ASCII path as WordPress stores it, as a browser sends it, and decoded. */
	private const LO  = 'caf%c3%a9';
	private const UP  = 'caf%C3%A9';
	private const DEC = 'café';

	/**
	 * Files and directories to remove in tear_down.
	 *
	 * @var array<int,string>
	 */
	private $cleanup = array();

	public function set_up(): void {
		parent::set_up();
		Stub::reset();
		$this->cleanup = array( Stub::root() );
	}

	public function tear_down(): void {
		foreach ( $this->cleanup as $path ) {
			if ( is_dir( $path ) ) {
				Stub::rrmdir( $path );
			} elseif ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		clearstatcache();
		parent::tear_down();
	}

	/**
	 * Load the shared helpers and one adapter file.
	 *
	 * @param string $slug Registry slug.
	 */
	private function load( string $slug ): void {
		aafm_siteops_load();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/cache/' . str_replace( '_', '-', $slug ) . '.php';
	}

	/**
	 * The canonical URL array for a path on the test site (http://example.org).
	 *
	 * @param string $path Path with a leading slash, percent escapes lower-case.
	 * @return array<string,mixed>
	 */
	private function url( string $path ): array {
		$url = aafm_siteops_canonical_url( home_url( $path ) );
		$this->assertIsArray( $url );
		return $url;
	}

	/**
	 * Create a file and its directories.
	 *
	 * @param string $file Absolute path.
	 * @return string The path.
	 */
	private function put( string $file ): string {
		wp_mkdir_p( dirname( $file ) );
		file_put_contents( $file, 'cached' );
		return $file;
	}

	/**
	 * Run an adapter function the way the dispatcher does, through the isolation wrapper.
	 *
	 * @param callable $callback The adapter call.
	 * @return mixed Its return value.
	 */
	private function call( callable $callback ) {
		$answer = aafm_siteops_vendor_call( $callback );
		$this->assertTrue( $answer['ok'], 'The adapter call threw.' );
		return $answer['value'];
	}

	/**
	 * Assert one adapter result, key by key.
	 *
	 * @param string    $state  Expected state.
	 * @param string    $reason Expected reason.
	 * @param bool|null $was    Expected was_cached.
	 * @param mixed     $actual The adapter's return.
	 * @param string    $note   Shown when the assertion fails.
	 */
	private function assertResult( string $state, string $reason, ?bool $was, $actual, string $note = '' ): void {
		$this->assertIsArray( $actual, $note );
		$this->assertSame( array( 'reason', 'state', 'was_cached' ), $this->sorted_keys( $actual ), $note );
		$this->assertSame( $state . ' ' . $reason, $actual['state'] . ' ' . $actual['reason'], $note );
		$this->assertSame( $was, $actual['was_cached'], $note );
	}

	/**
	 * Sorted keys of an array.
	 *
	 * @param array<string,mixed> $value Array.
	 * @return array<int,string>
	 */
	private function sorted_keys( array $value ): array {
		$keys = array_keys( $value );
		sort( $keys );
		return $keys;
	}

	/**
	 * Assert the registry row of one adapter against section 6.1 of the design.
	 *
	 * @param string                       $slug      Registry slug.
	 * @param string                       $basename  Plugin basename.
	 * @param array<int,array<int,string>> $detect    Detect list, any order.
	 * @param string                       $confirm   file or none.
	 * @param array<string,bool>           $flags     cli, vendor_http, recursive_url, rewarms.
	 * @return array<string,mixed> The row.
	 */
	private function assertRegistryRow( string $slug, string $basename, array $detect, string $confirm, array $flags ): array {
		$rows = aafm_site_cache_adapters();
		$this->assertArrayHasKey( $slug, $rows );
		$row = $rows[ $slug ];
		$this->assertSame( array( $basename ), $row['plugins'] );
		$this->assertEqualsCanonicalizing( $detect, $row['detect'] );
		$this->assertSame( array( 'post', 'url', 'all' ), $row['ops'] );
		$this->assertSame( $confirm, $row['confirm'] );
		foreach ( $flags as $key => $value ) {
			$this->assertSame( $value, $row[ $key ], $key );
		}
		return $row;
	}

	private function wpsc_dir( string $relative ): string {
		// The page directory of a path under the stub's supercache folder. Upper-case escapes.
		return Stub::root() . '/wpsc/supercache/example.org' . ( '' === $relative ? '' : '/' . $relative );
	}

	private function wpsc_ready(): void {
		aafm_siteops_stub_wp_super_cache_activate();
		$this->load( 'wp_super_cache' );
		$this->cleanup[] = $this->put( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	public function test_wp_super_cache_registry_row_and_detection(): void {
		$row = $this->assertRegistryRow(
			'wp_super_cache',
			'wp-super-cache/wp-cache.php',
			array( array( 'function', 'wp_cache_clear_cache' ), array( 'function', 'wpsc_delete_post_cache' ), array( 'function', 'wpsc_delete_url_cache' ), array( 'function', 'get_supercache_dir' ) ),
			'file',
			array(
				'cli'           => true,
				'vendor_http'   => false,
				'recursive_url' => false,
				'rewarms'       => false,
			)
		);
		aafm_siteops_stub_wp_super_cache_activate();
		$this->assertTrue( aafm_site_cache_detected( $row ) );
		$row['detect'][] = array( 'function', 'aafm_no_such_vendor_function' );
		$this->assertFalse( aafm_site_cache_detected( $row ) );
	}

	public function test_wp_super_cache_serving_is_false_while_wp_cache_is_off(): void {
		// Every other condition is met: the setting is on and the drop-in exists.
		$this->wpsc_ready();
		$this->assertFalse( defined( 'WP_CACHE' ) && WP_CACHE, 'The WordPress test suite always runs with WP_CACHE false.' );
		$this->assertFalse( $this->call( 'aafm_cache_wp_super_cache_serving' ) );
		// The true case needs a real site: acceptance case P1. The off switch: case N7.
	}

	public function test_wp_super_cache_post_purge_that_renames_is_a_stale_window(): void {
		$this->wpsc_ready();
		$file = $this->put( $this->wpsc_dir( 'acc-a' ) . '/index.html' );
		// The plugin's default: rename, do not delete (bench3 B6).
		Stub::$on['wpsc_delete_post_cache'] = static function () use ( $file ): void {
			rename( $file, $file . '.needs-rebuild' );
		};
		$url                                = $this->url( '/acc-a/' );
		$result                             = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_super_cache_purge_post( 41, $url );
			}
		);
		$this->assertResult( 'requested', 'stale_window', true, $result );
		$this->assertSame( array( array( 'wpsc_delete_post_cache', array( 41 ) ) ), Stub::$calls );
	}

	public function test_wp_super_cache_url_purge_that_deletes_is_purged(): void {
		$this->wpsc_ready();
		$file                              = $this->put( $this->wpsc_dir( 'acc-a' ) . '/index.html' );
		Stub::$on['wpsc_delete_url_cache'] = static function () use ( $file ): void {
			unlink( $file );
		};
		$url                               = $this->url( '/acc-a/' );
		$result                            = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_super_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( array( 'wpsc_delete_url_cache', array( 'http://example.org/acc-a/' ) ) ), Stub::$calls );
	}

	public function test_wp_super_cache_post_id_key_in_a_url_array_is_still_a_url_purge(): void {
		$this->wpsc_ready();
		$file                              = $this->put( $this->wpsc_dir( 'acc-a' ) . '/index.html' );
		Stub::$on['wpsc_delete_url_cache'] = static function () use ( $file ): void {
			unlink( $file );
		};
		$url                               = $this->url( '/acc-a/' ) + array( 'post_id' => 41 );
		$result                            = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_super_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( 'wpsc_delete_url_cache' ), Stub::names(), 'Only the adapter\'s own private key selects the post purge.' );
	}

	public function test_wp_super_cache_never_cached_page_is_not_found_whatever_the_plugin_returns(): void {
		$this->wpsc_ready();
		wp_mkdir_p( $this->wpsc_dir( '' ) );
		$url    = $this->url( '/acc-a/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_super_cache_purge_url( $url );
			}
		);
		// The stub returns true, as the real function does for a URL that was never cached.
		$this->assertResult( 'requested', 'not_found_in_cache', false, $result );
	}

	public function test_wp_super_cache_logged_in_copy_left_behind_is_still_cached(): void {
		$this->wpsc_ready();
		$this->put( $this->wpsc_dir( 'acc-a' ) . '/wp-cache-0123456789abcdef0123456789abcdef.php' );
		$url    = $this->url( '/acc-a/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_super_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'failed', 'still_cached', true, $result );
	}

	public function test_wp_super_cache_non_ascii_page_is_looked_for_under_upper_case_escapes(): void {
		$this->wpsc_ready();
		$file                              = $this->put( $this->wpsc_dir( self::UP ) . '/index.html' );
		Stub::$on['wpsc_delete_url_cache'] = static function () use ( $file ): void {
			unlink( $file );
		};
		$url                               = $this->url( '/' . self::LO . '/' );
		$result                            = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_super_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( array( 'wpsc_delete_url_cache', array( 'http://example.org/' . self::LO . '/' ) ) ), Stub::$calls );
	}

	public function test_wp_super_cache_home_option_with_another_scheme_falls_back_to_the_cache_path(): void {
		$this->wpsc_ready();
		$file                              = $this->put( $this->wpsc_dir( 'acc-a' ) . '/index.html' );
		Stub::$on['wpsc_delete_url_cache'] = static function () use ( $file ): void {
			unlink( $file );
		};
		$url                               = $this->url( '/acc-a/' );
		// From here the stored option says https while the canonical URL says http.
		add_filter(
			'pre_option_home',
			static function () {
				return 'https://example.org';
			}
		);
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_super_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
	}

	public function test_wp_super_cache_site_purge_passes_zero_and_reads_the_tree(): void {
		$this->wpsc_ready();
		$this->put( $this->wpsc_dir( 'acc-a' ) . '/index.html' );
		$this->put( $this->wpsc_dir( 'acc-page' ) . '/index.html' );
		$dir                              = $this->wpsc_dir( '' );
		Stub::$on['wp_cache_clear_cache'] = static function () use ( $dir ): void {
			Stub::rrmdir( $dir . '/acc-a' );
			Stub::rrmdir( $dir . '/acc-page' );
		};
		$this->assertResult( 'purged', '', true, $this->call( 'aafm_cache_wp_super_cache_purge_all' ) );
		$this->assertSame( array( array( 'wp_cache_clear_cache', array( 0 ) ) ), Stub::$calls );
	}

	public function test_wp_super_cache_site_purge_that_leaves_a_logged_in_copy_is_still_cached(): void {
		$this->wpsc_ready();
		$this->put( $this->wpsc_dir( 'acc-a' ) . '/index.html' );
		$this->put( $this->wpsc_dir( 'acc-a' ) . '/meta-wp-cache-0123456789abcdef0123456789abcdef.php' );
		$dir                              = $this->wpsc_dir( 'acc-a' );
		Stub::$on['wp_cache_clear_cache'] = static function () use ( $dir ): void {
			unlink( $dir . '/index.html' );
		};
		$this->assertResult( 'failed', 'still_cached', true, $this->call( 'aafm_cache_wp_super_cache_purge_all' ) );
	}

	private function wpfc_ready(): void {
		aafm_siteops_stub_wp_fastest_cache_activate();
		$this->load( 'wp_fastest_cache' );
		update_option( 'WpFastestCache', '{"wpFastestCacheStatus":"on"}' );
	}

	public function test_wp_fastest_cache_registry_row_and_detection(): void {
		$row = $this->assertRegistryRow(
			'wp_fastest_cache',
			'wp-fastest-cache/wpFastestCache.php',
			array( array( 'class', 'WpFastestCache' ), array( 'function', 'wpfc_clear_post_cache_by_id' ) ),
			'file',
			array(
				'cli'           => true,
				'vendor_http'   => true,
				'recursive_url' => false,
				'rewarms'       => false,
			)
		);
		aafm_siteops_stub_wp_fastest_cache_activate();
		$this->assertTrue( aafm_site_cache_detected( $row ) );
		$row['detect'][] = array( 'function', 'aafm_no_such_vendor_function' );
		$this->assertFalse( aafm_site_cache_detected( $row ) );
	}

	public function test_wp_fastest_cache_serving_reads_the_status_key(): void {
		aafm_siteops_stub_wp_fastest_cache_activate();
		$this->load( 'wp_fastest_cache' );
		delete_option( 'WpFastestCache' );
		$this->assertFalse( $this->call( 'aafm_cache_wp_fastest_cache_serving' ) );
		update_option( 'WpFastestCache', '{"wpFastestCacheLanguage":"eng"}' );
		$this->assertFalse( $this->call( 'aafm_cache_wp_fastest_cache_serving' ), 'Saved settings with caching off have no status key.' );
		update_option( 'WpFastestCache', '{"wpFastestCacheStatus":"on"}' );
		$this->assertTrue( $this->call( 'aafm_cache_wp_fastest_cache_serving' ) );
	}

	public function test_wp_fastest_cache_post_purge_calls_the_id_function_and_looks_under_the_decoded_name(): void {
		$this->wpfc_ready();
		$file                                    = $this->put( Stub::root() . '/wpfc/cache/all/' . self::DEC . '/index.html' );
		Stub::$on['wpfc_clear_post_cache_by_id'] = static function () use ( $file ): void {
			unlink( $file );
		};
		$url                                     = $this->url( '/' . self::LO . '/' );
		$result                                  = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_fastest_cache_purge_post( 41, $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( array( 'wpfc_clear_post_cache_by_id', array( 41 ) ) ), Stub::$calls );
	}

	public function test_wp_fastest_cache_url_purge_calls_the_url_function(): void {
		$this->wpfc_ready();
		$file                                = $this->put( Stub::root() . '/wpfc/cache/all/acc-a/index.html' );
		Stub::$on['wpfc_clear_cache_by_url'] = static function () use ( $file ): void {
			unlink( $file );
		};
		$url                                 = $this->url( '/acc-a/' );
		$result                              = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_fastest_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( array( 'wpfc_clear_cache_by_url', array( 'http://example.org/acc-a/' ) ) ), Stub::$calls );
	}

	public function test_wp_fastest_cache_never_cached_and_untouched_pages(): void {
		$this->wpfc_ready();
		wp_mkdir_p( Stub::root() . '/wpfc/cache/all' );
		$url  = $this->url( '/acc-a/' );
		$call = static function () use ( $url ) {
			return aafm_cache_wp_fastest_cache_purge_url( $url );
		};
		$this->assertResult( 'requested', 'not_found_in_cache', false, $this->call( $call ) );
		$this->put( Stub::root() . '/wpfc/cache/all/acc-a/index.html' );
		$this->assertResult( 'failed', 'still_cached', true, $this->call( $call ) );
	}

	public function test_wp_fastest_cache_explicit_vendor_failure_is_a_vendor_error(): void {
		$this->wpfc_ready();
		$this->put( Stub::root() . '/wpfc/cache/all/acc-a/index.html' );
		Stub::$on['wpfc_clear_cache_by_url'] = static function () {
			return array( 'success' => false );
		};
		$url                                 = $this->url( '/acc-a/' );
		$result                              = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_fastest_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'failed', 'vendor_error', null, $result );
	}

	public function test_wp_fastest_cache_premium_mobile_copies_downgrade_a_purge(): void {
		$this->wpfc_ready();
		$file = $this->put( Stub::root() . '/wpfc/cache/all/acc-a/index.html' );
		$this->put( Stub::root() . '/wpfc/cache/wpfc-mobile-cache/acc-a/index.html' );
		Stub::$on['wpfc_clear_cache_by_url'] = static function () use ( $file ): void {
			unlink( $file );
		};
		$url                                 = $this->url( '/acc-a/' );
		$result                              = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_fastest_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'requested', 'variants_unchecked', true, $result );
	}

	public function test_wp_fastest_cache_site_purge_reads_a_moved_folder_as_purged(): void {
		$this->wpfc_ready();
		$this->put( Stub::root() . '/wpfc/cache/all/acc-a/index.html' );
		$root                             = Stub::root() . '/wpfc/cache';
		Stub::$on['wpfc_clear_all_cache'] = static function () use ( $root ): void {
			// The real plugin moves cache/all to cache/tmpWpfc/<time> (bench3 B4).
			wp_mkdir_p( $root . '/tmpWpfc' );
			rename( $root . '/all', $root . '/tmpWpfc/1700000000' );
		};
		$this->assertResult( 'purged', '', true, $this->call( 'aafm_cache_wp_fastest_cache_purge_all' ) );
		$this->assertSame( array( 'wpfc_clear_all_cache' ), Stub::names() );
	}

	public function test_wp_fastest_cache_site_purge_with_premium_mobile_copies_is_variants_unchecked(): void {
		$this->wpfc_ready();
		$this->put( Stub::root() . '/wpfc/cache/all/acc-a/index.html' );
		$this->put( Stub::root() . '/wpfc/cache/wpfc-mobile-cache/acc-a/index.html' );
		$root                             = Stub::root() . '/wpfc/cache';
		Stub::$on['wpfc_clear_all_cache'] = static function () use ( $root ): void {
			// The plugin moves both folders away, so the mobile folder has to be read before the call.
			wp_mkdir_p( $root . '/tmpWpfc' );
			rename( $root . '/all', $root . '/tmpWpfc/1700000000' );
			rename( $root . '/wpfc-mobile-cache', $root . '/tmpWpfc/1700000001' );
		};
		$this->assertResult( 'requested', 'variants_unchecked', true, $this->call( 'aafm_cache_wp_fastest_cache_purge_all' ) );
	}

	private function w3tc_dir( string $relative ): string {
		// The page directory under Disk: Enhanced. Host directory, lower-case escapes.
		return WP_CONTENT_DIR . '/cache/page_enhanced/example.org' . ( '' === $relative ? '' : '/' . $relative );
	}

	private function w3tc_ready(): void {
		aafm_siteops_stub_w3_total_cache_activate();
		$this->load( 'w3_total_cache' );
		$this->cleanup[] = WP_CONTENT_DIR . '/cache/page_enhanced';
	}

	public function test_w3_total_cache_registry_row_and_detection(): void {
		$row = $this->assertRegistryRow(
			'w3_total_cache',
			'w3-total-cache/w3-total-cache.php',
			array( array( 'constant', 'W3TC' ), array( 'function', 'w3tc_config' ), array( 'function', 'w3tc_flush_post' ) ),
			'file',
			array(
				'cli'           => true,
				'vendor_http'   => true,
				'recursive_url' => false,
				'rewarms'       => false,
			)
		);
		aafm_siteops_stub_w3_total_cache_activate();
		$this->assertTrue( aafm_site_cache_detected( $row ) );
		$row['detect'][] = array( 'constant', 'AAFM_NO_SUCH_VENDOR_CONSTANT' );
		$this->assertFalse( aafm_site_cache_detected( $row ) );
	}

	public function test_w3_total_cache_serving_is_false_while_wp_cache_is_off(): void {
		// Every other condition is met: the page cache setting is on.
		$this->w3tc_ready();
		$this->assertFalse( defined( 'WP_CACHE' ) && WP_CACHE, 'The WordPress test suite always runs with WP_CACHE false.' );
		$this->assertFalse( $this->call( 'aafm_cache_w3_total_cache_serving' ) );
		// The true case needs a real site: acceptance case P1. The off switch: case N7.
	}

	public function test_w3_total_cache_post_purge_is_never_purged_and_forces_the_delayed_run(): void {
		$this->w3tc_ready();
		$file                                   = $this->put( $this->w3tc_dir( self::LO ) . '/_index_slash.html' );
		Stub::$on['execute_delayed_operations'] = static function () use ( $file ) {
			// The real flush renames, it does not delete (bench3 B1 to B5).
			rename( $file, $file . '_old' );
			return array( array( 'module' => 'pgcache' ) );
		};
		$url                                    = $this->url( '/' . self::LO . '/' );
		$result                                 = $this->call(
			static function () use ( $url ) {
				return aafm_cache_w3_total_cache_purge_post( 41, $url );
			}
		);
		$this->assertResult( 'requested', 'stale_window', true, $result );
		$this->assertSame( array( 'W3TC\Dispatcher::component', 'w3tc_flush_post', 'execute_delayed_operations' ), Stub::names() );
		$this->assertSame( array( array( 41, true ) ), Stub::args( 'w3tc_flush_post' ) );
		$this->assertSame( array( array( 'CacheFlush' ) ), Stub::args( 'W3TC\Dispatcher::component' ) );
	}

	public function test_w3_total_cache_url_purge_results(): void {
		$this->w3tc_ready();
		wp_mkdir_p( $this->w3tc_dir( '' ) );
		$url  = $this->url( '/acc-a/' );
		$call = static function () use ( $url ) {
			return aafm_cache_w3_total_cache_purge_url( $url );
		};
		$this->assertResult( 'requested', 'not_found_in_cache', false, $this->call( $call ) );
		$this->assertSame( array( array( 'http://example.org/acc-a/' ) ), Stub::args( 'w3tc_flush_url' ) );

		$this->put( $this->w3tc_dir( 'acc-a' ) . '/_index_slash.html_gzip' );
		$this->assertResult( 'failed', 'still_cached', true, $this->call( $call ), 'A live copy the flush left behind.' );

		Stub::$on['execute_delayed_operations'] = static function () {
			return array();
		};
		$this->assertResult( 'failed', 'nothing_sent', null, $this->call( $call ), 'The delayed run did not name the page cache.' );
	}

	public function test_w3_total_cache_page_path_that_leaves_the_cache_folder_is_a_vendor_error(): void {
		$this->w3tc_ready();
		$outside = Stub::root() . '/outside';
		wp_mkdir_p( $outside );
		wp_mkdir_p( $this->w3tc_dir( '' ) );
		symlink( $outside, $this->w3tc_dir( 'acc-a' ) );
		$url    = $this->url( '/acc-a/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_w3_total_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'failed', 'vendor_error', null, $result, 'Not nothing_sent: the plugin was never called.' );
		$this->assertSame( array( 'W3TC\Dispatcher::component' ), Stub::names(), 'The runner was looked up; nothing was flushed.' );
	}

	public function test_w3_total_cache_without_the_internal_method_is_deferred(): void {
		$this->w3tc_ready();
		Stub::$state['w3tc_no_runner'] = true;
		$url                           = $this->url( '/acc-a/' );
		$result                        = $this->call(
			static function () use ( $url ) {
				return aafm_cache_w3_total_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'requested', 'deferred', null, $result );
		$this->assertContains( 'w3tc_flush_url', Stub::names(), 'The flush is still issued.' );
		$this->assertResult( 'requested', 'deferred', null, $this->call( 'aafm_cache_w3_total_cache_purge_all' ) );
	}

	public function test_w3_total_cache_another_engine_is_a_stale_window_without_evidence(): void {
		$this->w3tc_ready();
		// No page_enhanced directory: memcached, Redis or Disk: Basic.
		$url    = $this->url( '/acc-a/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_w3_total_cache_purge_url( $url );
			}
		);
		$this->assertResult( 'requested', 'stale_window', null, $result );
		$this->assertResult( 'requested', 'deferred', null, $this->call( 'aafm_cache_w3_total_cache_purge_all' ) );
	}

	public function test_w3_total_cache_site_purge_is_a_stale_window_and_never_calls_flush_all(): void {
		$this->w3tc_ready();
		$file                                   = $this->put( $this->w3tc_dir( 'acc-a' ) . '/_index_slash.html' );
		$old                                    = $this->put( $this->w3tc_dir( 'acc-b' ) . '/_index_slash.html_old' );
		Stub::$on['execute_delayed_operations'] = static function () use ( $file ) {
			// The real whole-site flush renames every live copy to *_old as well (acceptance 2026-11-20).
			rename( $file, $file . '_old' );
			return array( array( 'module' => 'pgcache' ) );
		};
		$this->assertResult( 'requested', 'stale_window', true, $this->call( 'aafm_cache_w3_total_cache_purge_all' ) );
		$this->assertSame( array( 'W3TC\Dispatcher::component', 'w3tc_flush_posts', 'execute_delayed_operations' ), Stub::names() );
		$this->assertFileExists( $old, 'A renamed copy is not a live one.' );
	}

	public function test_w3_total_cache_site_purge_results_from_the_live_copies_only(): void {
		$this->w3tc_ready();
		$this->put( $this->w3tc_dir( 'acc-a' ) . '/_index_slash.html_old' );
		$this->assertResult( 'requested', 'not_found_in_cache', false, $this->call( 'aafm_cache_w3_total_cache_purge_all' ), 'Only renamed copies before the call.' );

		$this->put( $this->w3tc_dir( 'acc-b' ) . '/_index_slash.html_gzip' );
		$this->assertResult( 'failed', 'still_cached', true, $this->call( 'aafm_cache_w3_total_cache_purge_all' ), 'A live copy the flush left behind.' );
	}

	private function wpo_ready(): void {
		aafm_siteops_stub_wp_optimize_activate();
		$this->load( 'wp_optimize' );
	}

	public function test_wp_optimize_registry_row_and_detection(): void {
		$row = $this->assertRegistryRow(
			'wp_optimize',
			'wp-optimize/wp-optimize.php',
			array( array( 'function', 'WP_Optimize' ) ),
			'file',
			array(
				'cli'           => false,
				'vendor_http'   => true,
				'recursive_url' => false,
				'rewarms'       => false,
			)
		);
		aafm_siteops_stub_wp_optimize_activate();
		$this->assertTrue( aafm_site_cache_detected( $row ) );
		$row['detect'][] = array( 'function', 'aafm_no_such_vendor_function' );
		$this->assertFalse( aafm_site_cache_detected( $row ) );
	}

	public function test_wp_optimize_serving_is_false_while_wp_cache_is_off(): void {
		// Every other condition is met: both vendor constants are set and the plugin says enabled.
		$this->wpo_ready();
		$this->assertFalse( defined( 'WP_CACHE' ) && WP_CACHE, 'The WordPress test suite always runs with WP_CACHE false.' );
		$this->assertFalse( $this->call( 'aafm_cache_wp_optimize_serving' ) );
		// The true case needs a real site: acceptance case P1. The off switch: case N7.
	}

	public function test_wp_optimize_post_purge_looks_under_the_host_without_port_and_the_decoded_name(): void {
		$this->wpo_ready();
		$dir = Stub::root() . '/wpo/example.org/' . self::DEC;
		$this->put( $dir . '/index.html' );
		$this->put( $dir . '/index.html.gz' );
		$this->put( $dir . '/mobile.index.html' );
		Stub::$on['WPO_Page_Cache::delete_single_post_cache'] = static function () use ( $dir ): void {
			Stub::rrmdir( $dir );
		};
		$url    = $this->url( '/' . self::LO . '/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_wp_optimize_purge_post( 41, $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( array( 'WPO_Page_Cache::delete_single_post_cache', array( 41 ) ) ), Stub::$calls );
	}

	public function test_wp_optimize_url_purge_results(): void {
		$this->wpo_ready();
		wp_mkdir_p( Stub::root() . '/wpo/example.org' );
		$url  = $this->url( '/acc-a/' );
		$call = static function () use ( $url ) {
			return aafm_cache_wp_optimize_purge_url( $url );
		};
		// The stub returns true, as the real method does for a page that was never cached.
		$this->assertResult( 'requested', 'not_found_in_cache', false, $this->call( $call ) );
		$this->assertSame( array( array( 'WPO_Page_Cache::delete_cache_by_url', array( 'http://example.org/acc-a/' ) ) ), Stub::$calls );

		$this->put( Stub::root() . '/wpo/example.org/acc-a/mobile.index.html' );
		$this->assertResult( 'failed', 'still_cached', true, $this->call( $call ), 'A mobile copy the plugin left.' );
	}

	public function test_wp_optimize_site_purge_calls_purge_only(): void {
		$this->wpo_ready();
		$this->put( Stub::root() . '/wpo/example.org/acc-a/index.html' );
		// A copy under an alias host must not keep the result at still_cached.
		$this->put( Stub::root() . '/wpo/alias.example/acc-a/index.html' );
		$dir               = Stub::root() . '/wpo/example.org/acc-a';
		Stub::$on['purge'] = static function () use ( $dir ): void {
			Stub::rrmdir( $dir );
		};
		$this->assertResult( 'purged', '', true, $this->call( 'aafm_cache_wp_optimize_purge_all' ) );
		$this->assertSame( array( 'purge' ), Stub::names(), 'Never wpo_cache_flush(), which also flushes the object cache.' );
	}

	private function ce_ready(): void {
		aafm_siteops_stub_cache_enabler_activate();
		$this->load( 'cache_enabler' );
		$this->cleanup[] = $this->put( WP_CONTENT_DIR . '/advanced-cache.php' );
	}

	private function ce_clears_the_spelling_given(): callable {
		// A stand-in for the real purge: it clears only the directory of the spelling it is given.
		return static function ( $url ): void {
			Stub::rrmdir( Stub::root() . '/ce/example.org/' . trim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) );
		};
	}

	public function test_cache_enabler_registry_row_and_detection(): void {
		$row = $this->assertRegistryRow(
			'cache_enabler',
			'cache-enabler/cache-enabler.php',
			array( array( 'constant', 'CACHE_ENABLER_VERSION' ) ),
			'file',
			array(
				'cli'           => true,
				'vendor_http'   => false,
				'recursive_url' => false,
				'rewarms'       => false,
			)
		);
		aafm_siteops_stub_cache_enabler_activate();
		$this->assertTrue( aafm_site_cache_detected( $row ) );
		$row['detect'][] = array( 'constant', 'AAFM_NO_SUCH_VENDOR_CONSTANT' );
		$this->assertFalse( aafm_site_cache_detected( $row ) );
	}

	public function test_cache_enabler_serving_is_false_while_wp_cache_is_off(): void {
		// Every other condition is met: the cache directory constant and the drop-in exist.
		$this->ce_ready();
		$this->assertFalse( defined( 'WP_CACHE' ) && WP_CACHE, 'The WordPress test suite always runs with WP_CACHE false.' );
		$this->assertFalse( $this->call( 'aafm_cache_cache_enabler_serving' ) );
		// The true case needs a real site: acceptance case P1. The off switch: case N7.
	}

	public function test_cache_enabler_ascii_url_is_one_vendor_call(): void {
		$this->ce_ready();
		$this->put( Stub::root() . '/ce/example.org/acc-a/http-index.html' );
		Stub::$on['cache_enabler_clear_page_cache_by_url'] = $this->ce_clears_the_spelling_given();
		$url    = $this->url( '/acc-a/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_cache_enabler_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( array( 'cache_enabler_clear_page_cache_by_url', array( 'http://example.org/acc-a/' ) ) ), Stub::$calls );
	}

	public function test_cache_enabler_non_ascii_url_is_two_vendor_calls_and_both_spellings_are_checked(): void {
		$this->ce_ready();
		$this->put( Stub::root() . '/ce/example.org/' . self::LO . '/http-index.html' );
		$this->put( Stub::root() . '/ce/example.org/' . self::UP . '/https-index-mobile.html.gz' );
		Stub::$on['cache_enabler_clear_page_cache_by_url'] = $this->ce_clears_the_spelling_given();
		$url    = $this->url( '/' . self::LO . '/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_cache_enabler_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame(
			array(
				array( 'cache_enabler_clear_page_cache_by_url', array( 'http://example.org/' . self::LO . '/' ) ),
				array( 'cache_enabler_clear_page_cache_by_url', array( 'http://example.org/' . self::UP . '/' ) ),
			),
			Stub::$calls
		);
	}

	public function test_cache_enabler_copy_under_the_other_spelling_that_survives_is_a_variant_failure(): void {
		$this->ce_ready();
		$this->put( Stub::root() . '/ce/example.org/' . self::LO . '/http-index.html' );
		$this->put( Stub::root() . '/ce/example.org/' . self::UP . '/http-index.html' );
		// A vendor that clears the lower-case copy and ignores the second call.
		Stub::$on['cache_enabler_clear_page_cache_by_url'] = static function ( $url ): void {
			if ( false !== strpos( $url, '%c3' ) ) {
				Stub::rrmdir( Stub::root() . '/ce/example.org/' . self::LO );
			}
		};
		$url    = $this->url( '/' . self::LO . '/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_cache_enabler_purge_url( $url );
			}
		);
		$this->assertResult( 'failed', 'variant_still_cached', true, $result );
	}

	public function test_cache_enabler_post_purge_fires_the_post_action_then_the_upper_case_url(): void {
		$this->ce_ready();
		wp_mkdir_p( Stub::root() . '/ce/example.org' );
		$url    = $this->url( '/' . self::LO . '/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_cache_enabler_purge_post( 41, $url );
			}
		);
		$this->assertResult( 'requested', 'not_found_in_cache', false, $result );
		$this->assertSame(
			array(
				array( 'cache_enabler_clear_page_cache_by_post', array( 41 ) ),
				array( 'cache_enabler_clear_page_cache_by_url', array( 'http://example.org/' . self::UP . '/' ) ),
			),
			Stub::$calls
		);
	}

	public function test_cache_enabler_site_purge_fires_the_site_action_never_the_network_one(): void {
		$this->ce_ready();
		$this->put( Stub::root() . '/ce/example.org/acc-a/http-index.html' );
		Stub::$on['cache_enabler_clear_site_cache'] = static function (): void {
			Stub::rrmdir( Stub::root() . '/ce/example.org/acc-a' );
		};
		$this->assertResult( 'purged', '', true, $this->call( 'aafm_cache_cache_enabler_purge_all' ) );
		$this->assertSame( array( 'cache_enabler_clear_site_cache' ), Stub::names() );
	}

	private const HB_FILE = '0123456789abcdef0123456789abcdef';

	private function hb_dir( string $relative, bool $mobile = false ): string {
		// A page directory in the desktop or the mobile tree. The host directory carries the port when the site has one.
		return WP_CONTENT_DIR . '/wphb-cache/cache/' . ( $mobile ? 'mobile/' : '' ) . 'example.org' . ( '' === $relative ? '' : '/' . $relative );
	}

	private function hb_ready(): void {
		aafm_siteops_stub_hummingbird_activate();
		$this->load( 'hummingbird' );
		$this->cleanup[] = WP_CONTENT_DIR . '/wphb-cache';
	}

	private function hb_clears_the_path_given(): callable {
		// A stand-in for the real clear_cache(): it clears the directory of the path it is given, in both trees.
		return function ( $directory = '' ): void {
			Stub::rrmdir( $this->hb_dir( trim( (string) $directory, '/' ) ) );
			Stub::rrmdir( $this->hb_dir( trim( (string) $directory, '/' ), true ) );
		};
	}

	public function test_hummingbird_registry_row_and_detection(): void {
		$row = $this->assertRegistryRow(
			'hummingbird',
			'hummingbird-performance/wp-hummingbird.php',
			array( array( 'constant', 'WPHB_VERSION' ) ),
			'file',
			array(
				'cli'           => false,
				'vendor_http'   => true,
				'recursive_url' => false,
				'rewarms'       => false,
			)
		);
		aafm_siteops_stub_hummingbird_activate();
		$this->assertTrue( aafm_site_cache_detected( $row ) );
		$row['detect'][] = array( 'constant', 'AAFM_NO_SUCH_VENDOR_CONSTANT' );
		$this->assertFalse( aafm_site_cache_detected( $row ) );
	}

	public function test_hummingbird_serving_needs_the_module_and_its_action(): void {
		$this->hb_ready();
		$this->assertTrue( $this->call( 'aafm_cache_hummingbird_serving' ) );
		Stub::$state['hb_active'] = false;
		$this->assertFalse( $this->call( 'aafm_cache_hummingbird_serving' ), 'The page cache module is off.' );
		Stub::$state['hb_active'] = true;
		remove_all_actions( 'wphb_clear_page_cache' );
		$this->assertFalse( $this->call( 'aafm_cache_hummingbird_serving' ), 'Nothing listens on the purge action.' );
	}

	public function test_hummingbird_post_purge_fires_the_action(): void {
		$this->hb_ready();
		$this->put( $this->hb_dir( 'acc-a' ) . '/' . self::HB_FILE . '.html' );
		$dir                               = $this->hb_dir( 'acc-a' );
		Stub::$on['wphb_clear_page_cache'] = static function () use ( $dir ): void {
			Stub::rrmdir( $dir );
		};
		$url                               = $this->url( '/acc-a/' );
		$result                            = $this->call(
			static function () use ( $url ) {
				return aafm_cache_hummingbird_purge_post( 41, $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame( array( array( 'wphb_clear_page_cache', array( 41 ) ) ), Stub::$calls );
	}

	public function test_hummingbird_non_ascii_url_is_two_calls_over_four_directories(): void {
		$this->hb_ready();
		$this->put( $this->hb_dir( self::LO ) . '/' . self::HB_FILE . '.html' );
		// A logged-in visitor's copy is a .php file; a phone's copy sits in the mobile tree.
		$this->put( $this->hb_dir( self::UP ) . '/' . self::HB_FILE . '.php' );
		$this->put( $this->hb_dir( self::LO, true ) . '/' . self::HB_FILE . '.html' );
		Stub::$on['clear_cache'] = $this->hb_clears_the_path_given();
		$url                     = $this->url( '/' . self::LO . '/' );
		$result                  = $this->call(
			static function () use ( $url ) {
				return aafm_cache_hummingbird_purge_url( $url );
			}
		);
		$this->assertResult( 'purged', '', true, $result );
		$this->assertSame(
			array(
				array( 'clear_cache', array( '/' . self::LO . '/' ) ),
				array( 'clear_cache', array( '/' . self::UP . '/' ) ),
			),
			Stub::$calls
		);
	}

	public function test_hummingbird_mobile_copy_left_behind_is_still_cached(): void {
		$this->hb_ready();
		$this->put( $this->hb_dir( 'acc-a', true ) . '/' . self::HB_FILE . '.php' );
		$url    = $this->url( '/acc-a/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_hummingbird_purge_url( $url );
			}
		);
		$this->assertResult( 'failed', 'still_cached', true, $result );
		$this->assertSame( array( array( 'clear_cache', array( '/acc-a/' ) ) ), Stub::$calls, 'An ASCII path is one call.' );
	}

	public function test_hummingbird_placeholder_file_is_not_a_cached_page(): void {
		$this->hb_ready();
		$this->put( $this->hb_dir( 'acc-a' ) . '/index.html' );
		$url    = $this->url( '/acc-a/' );
		$result = $this->call(
			static function () use ( $url ) {
				return aafm_cache_hummingbird_purge_url( $url );
			}
		);
		$this->assertResult( 'requested', 'not_found_in_cache', false, $result );
	}

	public function test_hummingbird_home_url_is_a_single_slash(): void {
		$this->hb_ready();
		wp_mkdir_p( $this->hb_dir( '' ) );
		$url = $this->url( '/' );
		$this->call(
			static function () use ( $url ) {
				return aafm_cache_hummingbird_purge_url( $url );
			}
		);
		$this->assertSame( array( array( 'clear_cache', array( '/' ) ) ), Stub::$calls );
	}

	public function test_hummingbird_site_purge_reads_both_trees(): void {
		$this->hb_ready();
		$this->put( $this->hb_dir( 'acc-a' ) . '/' . self::HB_FILE . '.html' );
		$this->put( $this->hb_dir( 'acc-a', true ) . '/' . self::HB_FILE . '.html' );
		$desktop                 = $this->hb_dir( '' );
		Stub::$on['clear_cache'] = static function () use ( $desktop ): void {
			Stub::rrmdir( $desktop );
		};
		$this->assertResult( 'failed', 'still_cached', true, $this->call( 'aafm_cache_hummingbird_purge_all' ), 'The mobile tree still holds a page.' );
		$this->put( $this->hb_dir( 'acc-a' ) . '/' . self::HB_FILE . '.html' );
		$mobile                  = $this->hb_dir( '', true );
		Stub::$on['clear_cache'] = static function () use ( $desktop, $mobile ): void {
			Stub::rrmdir( $desktop );
			Stub::rrmdir( $mobile );
		};
		$this->assertResult( 'purged', '', true, $this->call( 'aafm_cache_hummingbird_purge_all' ), 'A host directory that is gone holds nothing.' );
		$this->assertSame( array( array(), array() ), Stub::args( 'clear_cache' ), 'A full clear takes no argument.' );
	}
}
