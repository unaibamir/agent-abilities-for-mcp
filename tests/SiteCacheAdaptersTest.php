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
}
