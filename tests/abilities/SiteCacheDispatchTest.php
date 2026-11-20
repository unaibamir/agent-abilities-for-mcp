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

	/**
	 * A result, shorter to write.
	 *
	 * @param string    $state      State.
	 * @param string    $reason     Reason.
	 * @param bool|null $was_cached Was cached.
	 * @return array<string,mixed>
	 */
	private function result( string $state, string $reason, ?bool $was_cached ): array {
		return array(
			'state'      => $state,
			'reason'     => $reason,
			'was_cached' => $was_cached,
		);
	}

	public function test_file_sequence_cannot_confirm_when_no_base_exists(): void {
		$called = 0;
		$result = aafm_site_cache_file_confirmed(
			array( array( '/aafm/no/such/base', 'page' ) ),
			'/^index\.html$/',
			static function () use ( &$called ): void {
				++$called;
			}
		);
		$this->assertSame( $this->result( 'requested', 'no_acknowledgement', null ), $result );
		$this->assertSame( 1, $called );
	}

	public function test_file_sequence_refuses_a_path_outside_the_base_without_calling_the_vendor(): void {
		$base   = $this->tmp();
		$called = 0;
		$result = aafm_site_cache_file_confirmed(
			array( array( $base, 'a/../b' ) ),
			'/^index\.html$/',
			static function () use ( &$called ): void {
				++$called;
			}
		);
		$this->assertSame( $this->result( 'failed', 'vendor_error', null ), $result );
		$this->assertSame( 0, $called );
	}

	public function test_file_sequence_reports_purged_only_when_a_file_was_there_and_is_gone(): void {
		$base = $this->tmp();
		$file = $base . '/page/index.html';
		$this->put( $file );
		$result = aafm_site_cache_file_confirmed(
			array( array( $base, 'page' ), array( $base, 'page' ) ),
			'/^index\.html$/',
			static function () use ( $file ): void {
				wp_delete_file( $file );
			}
		);
		$this->assertSame( $this->result( 'purged', '', true ), $result );
	}

	public function test_file_sequence_reports_not_found_when_nothing_was_cached(): void {
		$base   = $this->tmp();
		$called = 0;
		$result = aafm_site_cache_file_confirmed(
			array( array( $base, 'page' ) ),
			'/^index\.html$/',
			static function () use ( &$called ): bool {
				++$called;
				return true;
			}
		);
		$this->assertSame( $this->result( 'requested', 'not_found_in_cache', false ), $result );
		$this->assertSame( 1, $called );
	}

	public function test_file_sequence_reports_a_copy_the_vendor_left(): void {
		$base = $this->tmp();
		$this->put( $base . '/page/index.html' );
		$result = aafm_site_cache_file_confirmed(
			array( array( $base, 'page' ) ),
			'/^index\.html$/',
			static function (): bool {
				return true;
			}
		);
		$this->assertSame( $this->result( 'failed', 'still_cached', true ), $result );
	}

	public function test_file_sequence_reports_a_surviving_spelling(): void {
		$base  = $this->tmp();
		$lower = $base . '/caf%c3%a9/index.html';
		$upper = $base . '/caf%C3%A9/index.html';
		$this->put( $lower );
		$this->put( $upper );
		$result = aafm_site_cache_file_confirmed(
			array( array( $base, 'caf%c3%a9' ), array( $base, 'caf%C3%A9' ) ),
			'/^index\.html$/',
			static function () use ( $lower ): void {
				wp_delete_file( $lower );
			}
		);
		$this->assertSame( $this->result( 'failed', 'variant_still_cached', true ), $result );
	}

	/**
	 * Run the file sequence on one cached page with a vendor that replaces, touches or leaves it.
	 *
	 * @param string $vendor  'replace' | 'touch' | 'leave' | 'create'.
	 * @param bool   $rewarms Fourth argument of the sequence.
	 * @return array<string,mixed>
	 */
	private function recache_case( string $vendor, bool $rewarms ): array {
		$base = $this->tmp();
		$file = $base . '/page/index.html';
		if ( 'create' === $vendor ) {
			wp_mkdir_p( $base . '/page' );
		} else {
			$this->put( $file );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture in a temporary directory.
			touch( $file, time() - 100 );
		}
		$put = function ( string $path ): void {
			$this->put( $path );
		};
		return aafm_site_cache_file_confirmed(
			array( array( $base, 'page' ) ),
			'/^index\.html$/',
			static function () use ( $vendor, $file, $put ): void {
				clearstatcache();
				if ( 'replace' === $vendor ) {
					// A new file moved over the old one: a new inode, given the old file's mtime.
					$old = (int) filemtime( $file );
					$put( $file . '.new' );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- Test fixture in a temporary directory.
					rename( $file . '.new', $file );
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture in a temporary directory.
					touch( $file, $old );
				} elseif ( 'touch' === $vendor ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture in a temporary directory.
					touch( $file, time() );
				} elseif ( 'create' === $vendor ) {
					$put( $file );
				}
			},
			$rewarms
		);
	}

	public function test_recache_rule_reads_a_replaced_file_as_purged_and_recached(): void {
		$this->assertSame( $this->result( 'purged', 'recached', true ), $this->recache_case( 'replace', true ) );
	}

	public function test_without_the_recache_rule_a_replaced_file_is_still_cached(): void {
		$this->assertSame( $this->result( 'failed', 'still_cached', true ), $this->recache_case( 'replace', false ) );
	}

	public function test_recache_rule_never_calls_a_touched_file_new(): void {
		$this->assertSame( $this->result( 'requested', 'no_acknowledgement', true ), $this->recache_case( 'touch', true ) );
	}

	public function test_recache_rule_keeps_an_untouched_file_as_still_cached(): void {
		$this->assertSame( $this->result( 'failed', 'still_cached', true ), $this->recache_case( 'leave', true ) );
	}

	public function test_recache_rule_reads_a_page_first_cached_by_the_vendor_as_not_found(): void {
		$this->assertSame( $this->result( 'requested', 'not_found_in_cache', false ), $this->recache_case( 'create', true ) );
	}

	public function test_tree_sequence(): void {
		$noop = static function (): void {};

		$this->assertSame( $this->result( 'requested', 'no_acknowledgement', null ), aafm_site_cache_tree_confirmed( array( '/aafm/no/such/base' ), '/\.html$/', $noop ) );

		$empty = $this->tmp();
		$this->assertSame( $this->result( 'requested', 'not_found_in_cache', false ), aafm_site_cache_tree_confirmed( array( $empty ), '/\.html$/', $noop ) );

		$left = $this->tmp();
		$this->put( $left . '/a/index.html' );
		$this->assertSame( $this->result( 'failed', 'still_cached', true ), aafm_site_cache_tree_confirmed( array( $left ), '/\.html$/', $noop ) );

		$gone = $this->tmp();
		$this->put( $gone . '/all/a/index.html' );
		$tree = $gone . '/all';
		$this->assertSame(
			$this->result( 'purged', '', true ),
			aafm_site_cache_tree_confirmed(
				array( $tree, '/aafm/no/such/base' ),
				'/\.html$/',
				function () use ( $tree ): void {
					$this->remove_tree( $tree );
				}
			),
			'A base that is gone after a call that began with files in it is the positive result.'
		);
	}

	public function test_variants_downgrade_only_changes_a_purged_result(): void {
		$variants = $this->tmp();
		$purged   = $this->result( 'purged', '', true );
		$failed   = $this->result( 'failed', 'still_cached', true );

		$this->assertSame( $purged, aafm_site_cache_variants_downgrade( $purged, array( $variants, '/aafm/no/such/dir' ) ), 'An empty variant directory changes nothing.' );
		$this->put( $variants . '/m/index.html' );
		$this->assertSame( $this->result( 'requested', 'variants_unchecked', true ), aafm_site_cache_variants_downgrade( $purged, array( $variants ) ) );
		$this->assertSame( $failed, aafm_site_cache_variants_downgrade( $failed, array( $variants ) ) );
	}

	public function test_valid_result_accepts_only_the_pairs_of_the_contract(): void {
		$this->assertTrue( aafm_site_cache_valid_result( $this->result( 'purged', '', true ) ) );
		$this->assertTrue( aafm_site_cache_valid_result( $this->result( 'purged', 'recached', true ) ) );
		$this->assertTrue( aafm_site_cache_valid_result( $this->result( 'requested', 'stale_window', null ) ) );
		$this->assertTrue( aafm_site_cache_valid_result( $this->result( 'skipped', 'over_budget', null ) ) );
		$this->assertFalse( aafm_site_cache_valid_result( $this->result( 'requested', 'recached', true ) ) );
		$this->assertFalse( aafm_site_cache_valid_result( $this->result( 'purged', 'still_cached', true ) ) );
		$this->assertFalse( aafm_site_cache_valid_result( $this->result( 'done', '', true ) ) );
		$this->assertFalse( aafm_site_cache_valid_result( array( 'state' => 'purged' ) ) );
		$this->assertFalse( aafm_site_cache_valid_result( array_merge( $this->result( 'purged', '', true ), array( 'path' => '/var/cache' ) ) ) );
		$this->assertFalse( aafm_site_cache_valid_result( true ) );
		$this->assertFalse(
			aafm_site_cache_valid_result(
				array(
					'state'      => 'purged',
					'reason'     => '',
					'was_cached' => 'yes',
				)
			)
		);
	}

	/**
	 * A canonical URL array for a test.
	 *
	 * @param string $path Path.
	 * @return array<string,mixed>
	 */
	private function url( string $path ): array {
		$url = aafm_siteops_canonical_url( $path );
		$this->assertIsArray( $url );
		return $url;
	}

	public function test_serving_map_holds_true_false_or_null_for_ready_adapters_only(): void {
		Stub::register(
			array(
				'fake_a'       => Stub::entry( 'Fake A' ),
				'fake_b'       => Stub::entry( 'Fake B' ),
				'fake_c'       => Stub::entry( 'Fake C' ),
				'fake_partial' => Stub::entry( 'Fake Partial' ),
				'fake_absent'  => Stub::entry( 'Fake Absent', array( 'detect' => array( array( 'function', 'aafm_no_such_function' ) ) ) ),
			)
		);
		Stub::$serving = array(
			'fake_b' => false,
			'fake_c' => 'throw',
		);
		$this->assertSame(
			array(
				'fake_a' => true,
				'fake_b' => false,
				'fake_c' => null,
			),
			aafm_site_cache_serving_map()
		);

		Stub::$serving = array( 'fake_a' => 1 );
		$this->assertFalse( aafm_site_cache_serving_map()['fake_a'], 'Only an exact true is serving.' );
	}

	public function test_fan_out_gives_one_row_per_adapter_in_registry_order(): void {
		Stub::register(
			array(
				'fake_a' => Stub::entry( 'Fake A' ),
				'fake_b' => Stub::entry( 'Fake B' ),
			)
		);
		Stub::$purge = array( 'fake_b' => $this->result( 'requested', 'no_acknowledgement', null ) );
		$url         = $this->url( '/about/' );

		$rows = aafm_site_cache_fan_out(
			'post',
			array(
				'post_id' => 7,
				'url'     => $url,
			),
			aafm_site_cache_serving_map()
		);

		$this->assertSame(
			array(
				array(
					'provider'   => 'fake_a',
					'label'      => 'Fake A',
					'state'      => 'purged',
					'reason'     => '',
					'was_cached' => true,
				),
				array(
					'provider'   => 'fake_b',
					'label'      => 'Fake B',
					'state'      => 'requested',
					'reason'     => 'no_acknowledgement',
					'was_cached' => null,
				),
			),
			$rows
		);
		$this->assertSame( array( array( 'purge_post', array( 7, $url ) ) ), Stub::purge_calls( 'fake_a' ) );

		Stub::$calls = array();
		aafm_site_cache_fan_out( 'url', array( 'url' => $url ), aafm_site_cache_serving_map() );
		$this->assertSame( array( array( 'purge_url', array( $url ) ) ), Stub::purge_calls( 'fake_b' ) );

		Stub::$calls = array();
		aafm_site_cache_fan_out( 'all', array(), aafm_site_cache_serving_map() );
		$this->assertSame( array( array( 'purge_all', array() ) ), Stub::purge_calls( 'fake_a' ) );
	}

	public function test_fan_out_skips_what_is_missing_unsupported_or_not_caching(): void {
		update_option( 'active_plugins', array( 'fake-old/plugin.php' ) );
		Stub::register(
			array(
				'fake_absent'  => Stub::entry( 'Fake Absent', array( 'detect' => array( array( 'function', 'aafm_no_such_function' ) ) ) ),
				'fake_old'     => Stub::entry( 'Fake Old', array( 'detect' => array( array( 'function', 'aafm_no_such_function' ) ) ) ),
				'fake_partial' => Stub::entry( 'Fake Partial' ),
				'fake_a'       => Stub::entry( 'Fake A', array( 'ops' => array( 'post', 'all' ) ) ),
				'fake_b'       => Stub::entry( 'Fake B' ),
				'fake_c'       => Stub::entry( 'Fake C' ),
			)
		);
		Stub::$serving = array(
			'fake_b' => false,
			'fake_c' => 'throw',
		);

		$rows = aafm_site_cache_fan_out( 'url', array( 'url' => $this->url( '/about/' ) ), aafm_site_cache_serving_map() );
		$got  = array();
		foreach ( $rows as $row ) {
			$got[ $row['provider'] ] = $row['state'] . ' ' . $row['reason'];
		}

		$this->assertSame(
			array(
				'fake_old'     => 'skipped unsupported_version',
				'fake_partial' => 'skipped unsupported_version',
				'fake_a'       => 'skipped unsupported_operation',
				'fake_b'       => 'skipped not_caching',
				'fake_c'       => 'failed vendor_error',
			),
			$got,
			'An undetected plugin that is not active has no row; one that is active reads as an unsupported version.'
		);
		$this->assertSame( array(), Stub::purge_calls( 'fake_a' ) );
		$this->assertSame( array(), Stub::purge_calls( 'fake_b' ) );
		$this->assertSame( array(), Stub::purge_calls( 'fake_c' ) );
	}

	public function test_fan_out_skips_an_adapter_that_does_not_work_in_a_cli_process(): void {
		Stub::register(
			array(
				'fake_a' => Stub::entry( 'Fake A', array( 'cli' => false ) ),
				'fake_b' => Stub::entry( 'Fake B' ),
			)
		);
		add_filter( 'aafm_siteops_is_cli', '__return_true' );
		$rows = aafm_site_cache_fan_out( 'all', array(), aafm_site_cache_serving_map() );
		$this->assertSame( 'skipped', $rows[0]['state'] );
		$this->assertSame( 'unsupported_operation', $rows[0]['reason'] );
		$this->assertSame( 'purged', $rows[1]['state'] );
	}

	public function test_fan_out_contains_a_throw_and_a_malformed_answer_and_goes_on(): void {
		Stub::register(
			array(
				'fake_a' => Stub::entry( 'Fake A' ),
				'fake_b' => Stub::entry( 'Fake B' ),
				'fake_c' => Stub::entry( 'Fake C' ),
			)
		);
		Stub::$purge = array(
			'fake_a' => 'throw',
			'fake_b' => true,
		);
		$rows        = aafm_site_cache_fan_out( 'all', array(), aafm_site_cache_serving_map() );
		$this->assertSame( array( 'failed', 'vendor_error', null ), array( $rows[0]['state'], $rows[0]['reason'], $rows[0]['was_cached'] ) );
		$this->assertSame( array( 'failed', 'vendor_error', null ), array( $rows[1]['state'], $rows[1]['reason'], $rows[1]['was_cached'] ) );
		$this->assertSame( 'purged', $rows[2]['state'] );

		Stub::$purge = array(
			'fake_a' => $this->result( 'requested', 'recached', true ),
			'fake_b' => $this->result( 'purged', 'recached', true ),
		);
		$rows        = aafm_site_cache_fan_out( 'all', array(), aafm_site_cache_serving_map() );
		$this->assertSame( 'failed vendor_error', $rows[0]['state'] . ' ' . $rows[0]['reason'], 'requested with recached is not a pair of the contract.' );
		$this->assertSame( 'purged recached', $rows[1]['state'] . ' ' . $rows[1]['reason'] );
	}

	public function test_fan_out_routes_a_post_whose_url_is_the_home_url_to_the_url_purge(): void {
		Stub::register(
			array(
				'fake_a' => Stub::entry( 'Fake A' ),
				'fake_b' => Stub::entry( 'Fake B', array( 'ops' => array( 'post', 'all' ) ) ),
			)
		);
		$home = $this->url( '/' );
		$this->assertTrue( $home['is_home'] );

		$rows = aafm_site_cache_fan_out(
			'post',
			array(
				'post_id' => 9,
				'url'     => $home,
			),
			aafm_site_cache_serving_map()
		);
		$this->assertSame( array( array( 'purge_url', array( $home ) ) ), Stub::purge_calls( 'fake_a' ) );
		$this->assertSame( 'skipped unsupported_operation', $rows[1]['state'] . ' ' . $rows[1]['reason'], 'The home rules are the URL rules, so the operation tested is url.' );
		$this->assertSame( array(), Stub::purge_calls( 'fake_b' ) );
	}

	public function test_fan_out_starts_no_adapter_after_the_wall_time(): void {
		add_filter(
			'aafm_site_purge_budget',
			static function ( $settings ) {
				$settings['wall_seconds'] = 1;
				return $settings;
			}
		);
		Stub::register(
			array(
				'fake_a' => Stub::entry( 'Fake A' ),
				'fake_b' => Stub::entry( 'Fake B' ),
			)
		);
		$purged      = $this->result( 'purged', '', true );
		Stub::$purge = array(
			'fake_a' => static function () use ( $purged ): array {
				$until = microtime( true ) + 1.2;
				while ( microtime( true ) < $until ) {
					continue;
				}
				return $purged;
			},
		);

		$rows = aafm_site_cache_fan_out( 'all', array(), aafm_site_cache_serving_map() );
		$this->assertSame( 'purged', $rows[0]['state'] );
		$this->assertSame( 'skipped over_budget', $rows[1]['state'] . ' ' . $rows[1]['reason'] );
		$this->assertSame( array(), Stub::purge_calls( 'fake_b' ) );
	}

	public function test_recache_rule_cannot_confirm_when_the_scan_before_the_call_fails(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Running as root: a directory cannot be made unreadable.' );
		}
		$one = $this->tmp();
		$two = $this->tmp();
		$this->put( $one . '/page/index.html' );
		$this->put( $two . '/page/index.html' );
		// The second copy cannot be read before the call and can after it, unchanged.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture in a temporary directory.
		chmod( $two . '/page', 0000 );

		$result = aafm_site_cache_file_confirmed(
			array( array( $one, 'page' ), array( $two, 'page' ) ),
			'/^index\.html$/',
			static function () use ( $one, $two ): void {
				wp_delete_file( $one . '/page/index.html' );
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture in a temporary directory.
				chmod( $two . '/page', 0755 );
			},
			true
		);

		$this->assertSame( $this->result( 'requested', 'no_acknowledgement', null ), $result );
	}

	public function test_status_only_reports_an_object_cache_drop_in(): void {
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/cache/status-only.php';
		$before = wp_using_ext_object_cache();

		wp_using_ext_object_cache( false );
		$this->assertNotContains( 'object_cache', wp_list_pluck( aafm_site_cache_status_only(), 'kind' ) );

		wp_using_ext_object_cache( true );
		$rows = wp_list_pluck( aafm_site_cache_status_only(), 'label', 'provider' );
		// WordPress ignores a null argument, so the flag is put back as a boolean.
		wp_using_ext_object_cache( (bool) $before );

		$this->assertArrayHasKey( 'object_cache', $rows );
		$this->assertNotSame( '', $rows['object_cache'] );
		$this->assertLessThanOrEqual( 60, strlen( $rows['object_cache'] ) );
		$this->assertLessThanOrEqual( 60, strlen( aafm_site_cache_drop_in_name() ) );
	}

	public function test_status_only_names_a_page_cache_plugin_these_tools_do_not_cover(): void {
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/cache/status-only.php';
		if ( ! defined( 'SWCFPC_PLUGIN_PATH' ) ) {
			define( 'SWCFPC_PLUGIN_PATH', '/aafm/test/only' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedConstantFound -- Super Page Cache's own constant, defined to test its status-only row.
		}
		$found = null;
		foreach ( aafm_site_cache_status_only() as $row ) {
			$this->assertSame( array( 'provider', 'label', 'kind' ), array_keys( $row ) );
			if ( 'super_page_cache' === $row['provider'] ) {
				$found = $row;
			}
		}
		$this->assertSame(
			array(
				'provider' => 'super_page_cache',
				'label'    => 'Super Page Cache',
				'kind'     => 'page_cache_not_covered',
			),
			$found
		);
		$this->assertStringNotContainsString( '/aafm/test/only', (string) wp_json_encode( aafm_site_cache_status_only() ) );
	}
}
