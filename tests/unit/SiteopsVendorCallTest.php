<?php
/**
 * The vendor call wrapper, the HTTP guard and the cache file helpers of the Site Ops tools.
 *
 * @package AgentAbilitiesForMCP
 */

declare( strict_types=1 );

namespace AAFM\Tests\Unit;

use AAFM\Tests\TestCase;

final class SiteopsVendorCallTest extends TestCase {

	/**
	 * Temporary directories made by a test, removed in tear_down.
	 *
	 * @var string[]
	 */
	private $dirs = array();

	public function set_up(): void {
		parent::set_up();
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/vendor-call.php';
	}

	public function tear_down(): void {
		foreach ( $this->dirs as $dir ) {
			$this->remove_tree( $dir );
		}
		$this->dirs = array();
		aafm_siteops_http_guard_end();
		remove_filter( 'http_request_args', 'aafm_siteops_http_guard_args', PHP_INT_MAX );
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

	/**
	 * The Host header as PHP sees it now.
	 *
	 * @return string|null
	 */
	private static function host(): ?string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- Read to assert what the wrapper set; never used as input.
		return isset( $_SERVER['HTTP_HOST'] ) ? (string) $_SERVER['HTTP_HOST'] : null;
	}

	public function test_superglobals_are_empty_during_the_call_and_restored_after(): void {
		$_GET     = array( 'action' => 'g' );
		$_POST    = array( 'post_id' => '7' );
		$_REQUEST = array( 'url' => 'r' );

		$seen   = null;
		$result = aafm_siteops_vendor_call(
			static function () use ( &$seen ) {
				$seen = array( $_GET, $_POST, $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification -- Asserting the wrapper emptied them.
				return 'value';
			}
		);

		$this->assertSame( array( array(), array(), array() ), $seen );
		$this->assertSame(
			array(
				'ok'    => true,
				'value' => 'value',
			),
			$result
		);
		$this->assertSame( array( 'action' => 'g' ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- Asserting the wrapper restored it.
		$this->assertSame( array( 'post_id' => '7' ), $_POST ); // phpcs:ignore WordPress.Security.NonceVerification -- Asserting the wrapper restored it.
		$this->assertSame( array( 'url' => 'r' ), $_REQUEST ); // phpcs:ignore WordPress.Security.NonceVerification -- Asserting the wrapper restored it.
		$_GET     = array();
		$_POST    = array();
		$_REQUEST = array();
	}

	/**
	 * A callable that throws, as an Exception and as an Error.
	 *
	 * @return array<string,array{0:callable}>
	 */
	public function throwers(): array {
		return array(
			'exception' => array(
				static function (): void {
					throw new \RuntimeException( 'boom' );
				},
			),
			'error'     => array(
				static function (): void {
					throw new \Error( 'boom' );
				},
			),
		);
	}

	/**
	 * A throw leaves nothing changed behind.
	 *
	 * @dataProvider throwers
	 *
	 * @param callable $thrower Callable that throws.
	 */
	public function test_superglobals_and_host_are_restored_when_the_callable_throws( callable $thrower ): void {
		$_GET                 = array( 'a' => '1' );
		$_SERVER['HTTP_HOST'] = 'other.example';

		$result = aafm_siteops_vendor_call( $thrower );

		$this->assertSame(
			array(
				'ok'    => false,
				'value' => null,
			),
			$result
		);
		$this->assertSame( array( 'a' => '1' ), $_GET ); // phpcs:ignore WordPress.Security.NonceVerification -- Asserting the wrapper restored it.
		$this->assertSame( 'other.example', self::host() );
		$_GET = array();
	}

	public function test_the_host_header_is_the_site_host_during_the_call(): void {
		$this->assertSame( 'example.org', aafm_siteops_canonical_host() );

		$_SERVER['HTTP_HOST'] = 'other.example';
		$seen                 = null;
		aafm_siteops_vendor_call(
			static function () use ( &$seen ): void {
				$seen = self::host();
			}
		);
		$this->assertSame( 'example.org', $seen );
		$this->assertSame( 'other.example', self::host() );
	}

	public function test_a_host_header_that_was_not_set_is_unset_again(): void {
		$before = self::host();
		unset( $_SERVER['HTTP_HOST'] );

		$seen = null;
		aafm_siteops_vendor_call(
			static function () use ( &$seen ): void {
				$seen = self::host();
			}
		);
		$this->assertSame( 'example.org', $seen );
		$this->assertArrayNotHasKey( 'HTTP_HOST', $_SERVER );

		if ( null !== $before ) {
			$_SERVER['HTTP_HOST'] = $before;
		}
	}

	public function test_the_canonical_host_carries_a_port_when_the_home_url_has_one(): void {
		update_option( 'home', 'http://LocalHost:8072' );
		$this->assertSame( 'localhost:8072', aafm_siteops_canonical_host() );
	}

	public function test_wp_die_becomes_ok_false(): void {
		$result = aafm_siteops_vendor_call(
			static function (): string {
				wp_die( 'vendor refused' );
				return 'unreached';
			}
		);
		$this->assertFalse( $result['ok'] );
		$this->assertNull( $result['value'] );
		$this->assertFalse( has_filter( 'wp_die_handler', '__return_false' ) );
	}

	public function test_output_is_discarded_and_buffers_are_put_back(): void {
		ob_start();
		$mine  = ob_get_level();
		$inner = null;

		aafm_siteops_vendor_call(
			static function () use ( &$inner ): void {
				echo 'vendor noise';
				ob_start();
				ob_start();
				echo 'more noise';
				$inner = ob_get_level();
			}
		);

		$this->assertSame( $mine + 3, $inner );
		$this->assertSame( $mine, ob_get_level(), 'Our buffer and the two the callable left open are closed; the one opened before is not.' );
		$this->assertSame( '', ob_get_clean(), 'Nothing the callable printed reached the buffer that was open before the call.' );
	}

	public function test_is_cli_follows_its_filter(): void {
		$this->assertFalse( aafm_siteops_is_cli() );
		add_filter( 'aafm_siteops_is_cli', '__return_true' );
		$this->assertTrue( aafm_siteops_is_cli() );
		remove_filter( 'aafm_siteops_is_cli', '__return_true' );
	}

	public function test_no_http_call_refuses_a_request_and_removes_its_filter(): void {
		$before = has_filter( 'pre_http_request' );
		$result = aafm_siteops_no_http_call(
			static function () {
				return wp_remote_get( 'https://example.invalid/licence' );
			}
		);
		$this->assertTrue( $result['ok'] );
		$this->assertInstanceOf( \WP_Error::class, $result['value'] );
		$this->assertSame( 'aafm_site_no_http', $result['value']->get_error_code() );
		$this->assertSame( $before, has_filter( 'pre_http_request' ) );
	}

	/**
	 * Requests made through the HTTP API in a guard test are answered here, after the guard ran.
	 *
	 * @param array<int,array{url:string,args:array<string,mixed>}> $log Filled with every request that reached the end.
	 * @return callable The filter, to remove afterwards.
	 */
	private function answer_requests( array &$log ): callable {
		$answer = static function ( $pre, $args, $url ) use ( &$log ) {
			if ( false !== $pre ) {
				return $pre;
			}
			$log[] = array(
				'url'  => (string) $url,
				'args' => (array) $args,
			);
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array(
					'code'    => 200,
					'message' => 'OK',
				),
				'cookies'  => array(),
				'filename' => null,
			);
		};
		add_filter( 'pre_http_request', $answer, PHP_INT_MAX, 3 );
		return $answer;
	}

	public function test_the_guard_counts_and_caps_requests_between_begin_and_end(): void {
		$cap = static function ( array $settings ): array {
			$settings['http_cap'] = 2;
			return $settings;
		};
		add_filter( 'aafm_site_purge_budget', $cap );

		aafm_siteops_http_guard_begin();
		$log    = array();
		$answer = $this->answer_requests( $log );

		$first  = wp_remote_get( 'https://cdn.example/one', array( 'timeout' => 30 ) );
		$second = wp_remote_get( 'https://cdn.example/two' );
		$third  = wp_remote_get( 'https://cdn.example/three' );

		$this->assertIsArray( $first );
		$this->assertIsArray( $second );
		$this->assertInstanceOf( \WP_Error::class, $third );
		$this->assertSame( 'aafm_site_vendor_http_cap', $third->get_error_code() );
		$this->assertCount( 2, $log );
		$this->assertSame( 0, $log[0]['args']['redirection'] );
		$this->assertSame( 3, $log[0]['args']['timeout'] );

		aafm_siteops_http_guard_end();
		remove_filter( 'pre_http_request', $answer, PHP_INT_MAX );
		remove_filter( 'aafm_site_purge_budget', $cap );
	}

	public function test_a_request_somebody_else_already_answered_is_not_counted(): void {
		$cap = static function ( array $settings ): array {
			$settings['http_cap'] = 1;
			return $settings;
		};
		add_filter( 'aafm_site_purge_budget', $cap );
		$early = static function () {
			return new \WP_Error( 'answered_early' );
		};
		add_filter( 'pre_http_request', $early, 1 );

		aafm_siteops_http_guard_begin();
		for ( $i = 0; $i < 4; $i++ ) {
			$result = wp_remote_get( 'https://cdn.example/x' );
			$this->assertInstanceOf( \WP_Error::class, $result );
			$this->assertSame( 'answered_early', $result->get_error_code() );
		}
		aafm_siteops_http_guard_end();

		remove_filter( 'pre_http_request', $early, 1 );
		remove_filter( 'aafm_site_purge_budget', $cap );
	}

	public function test_after_end_only_requests_to_this_site_are_clamped_and_nothing_is_counted(): void {
		aafm_siteops_http_guard_begin();
		aafm_siteops_http_guard_end();

		$this->assertFalse( has_filter( 'pre_http_request', 'aafm_siteops_http_guard_pre' ) );
		$this->assertNotFalse( has_filter( 'http_request_args', 'aafm_siteops_http_guard_args' ) );

		$log    = array();
		$answer = $this->answer_requests( $log );
		for ( $i = 0; $i < 12; $i++ ) {
			$this->assertIsArray(
				wp_remote_get(
					'https://cdn.example/after',
					array(
						'timeout'     => 30,
						'redirection' => 5,
					)
				)
			);
		}
		$this->assertSame( 30, $log[0]['args']['timeout'] );
		$this->assertSame( 5, $log[0]['args']['redirection'] );

		wp_remote_get(
			'http://example.org/page/',
			array(
				'timeout'     => 30,
				'redirection' => 5,
			)
		);
		$own = end( $log );
		$this->assertSame( 0, $own['args']['redirection'] );
		$this->assertSame( 3, $own['args']['timeout'] );

		remove_filter( 'pre_http_request', $answer, PHP_INT_MAX );
	}

	public function test_in_a_cli_process_both_filters_are_gone_after_end(): void {
		add_filter( 'aafm_siteops_is_cli', '__return_true' );
		aafm_siteops_http_guard_begin();
		aafm_siteops_http_guard_end();
		remove_filter( 'aafm_siteops_is_cli', '__return_true' );

		$this->assertFalse( has_filter( 'pre_http_request', 'aafm_siteops_http_guard_pre' ) );
		$this->assertFalse( has_filter( 'http_request_args', 'aafm_siteops_http_guard_args' ) );
	}

	public function test_path_spellings(): void {
		update_option( 'home', 'http://localhost:8084' );
		require_once AAFM_PLUGIN_DIR . 'includes/siteops/url.php';
		$url = aafm_siteops_canonical_url( '/नमस्ते/' );
		$this->assertIsArray( $url );

		$lower = strtolower( rawurlencode( 'नमस्ते' ) );
		$this->assertSame(
			array(
				'host'     => 'localhost',
				'hostport' => 'localhost:8084',
				'lo'       => $lower,
				'up'       => rawurlencode( 'नमस्ते' ),
				'dec'      => 'नमस्ते',
			),
			aafm_siteops_path_spellings( $url )
		);
		$this->assertSame( 'http://localhost:8084/' . rawurlencode( 'नमस्ते' ) . '/', aafm_siteops_upper_url( $url ) );

		$ascii = aafm_siteops_canonical_url( '/about/team/' );
		$this->assertIsArray( $ascii );
		$spell = aafm_siteops_path_spellings( $ascii );
		$this->assertSame( 'about/team', $spell['lo'] );
		$this->assertSame( 'about/team', $spell['up'] );
		$this->assertSame( 'about/team', $spell['dec'] );
		$this->assertSame( $ascii['url'], aafm_siteops_upper_url( $ascii ) );
	}

	public function test_home_spellings(): void {
		update_option( 'home', 'https://Example.com/blog' );
		$this->assertSame(
			array(
				'host'     => 'example.com',
				'hostport' => 'example.com',
				'lo'       => 'blog',
				'up'       => 'blog',
				'dec'      => 'blog',
			),
			aafm_siteops_home_spellings()
		);
	}

	public function test_confined_path(): void {
		$base = $this->tmp();
		$this->put( $base . '/site/page/index.html' );
		$outside = $this->tmp();
		$this->put( $outside . '/secret.txt' );
		symlink( $outside, $base . '/site/link' );

		$this->assertSame( $base, aafm_siteops_confined_path( $base, '' ) );
		$this->assertSame( $base . '/site/page', aafm_siteops_confined_path( $base, 'site/page' ) );
		$this->assertSame( $base . '/site/not-there/yet', aafm_siteops_confined_path( $base, 'site/not-there/yet' ) );
		$this->assertNull( aafm_siteops_confined_path( $base, 'site/../site' ) );
		$this->assertNull( aafm_siteops_confined_path( $base, 'site/./page' ) );
		$this->assertNull( aafm_siteops_confined_path( $base, '/etc' ) );
		$this->assertNull( aafm_siteops_confined_path( $base, 'site//page' ) );
		$this->assertNull( aafm_siteops_confined_path( $base, 'site/link' ) );
		$this->assertNull( aafm_siteops_confined_path( $base, 'site/link/deeper' ) );
		$this->assertNull( aafm_siteops_confined_path( $base . '/missing', 'x' ) );
		$this->assertNull( aafm_siteops_confined_path( $base . '/site/page/index.html', '' ) );
	}

	public function test_cache_files_present_matches_by_regex_only(): void {
		$base = $this->tmp();
		$this->put( $base . '/page/index.html' );
		$regex = '/^[0-9a-f]{32}\.html$/';

		$this->assertFalse( aafm_siteops_cache_files_present( $base . '/page', $regex ), 'A placeholder index.html is not a cached page.' );
		$this->put( $base . '/page/' . md5( 'a' ) . '.html' );
		$this->assertTrue( aafm_siteops_cache_files_present( $base . '/page', $regex ) );
		$this->assertFalse( aafm_siteops_cache_files_present( $base . '/missing', $regex ) );
		$this->assertFalse( aafm_siteops_cache_files_present( $base, '/^page$/' ), 'A directory is never a cache file.' );
	}

	public function test_cache_files_stat_returns_mtime_and_inode(): void {
		$base = $this->tmp();
		$file = $base . '/page/index.html';
		$this->put( $file );

		$this->assertSame( array(), aafm_siteops_cache_files_stat( $base . '/missing', '/^index\.html$/' ) );
		$this->assertSame(
			array( 'index.html' => array( (int) filemtime( $file ), (int) fileinode( $file ) ) ),
			aafm_siteops_cache_files_stat( $base . '/page', '/^index\.html$/' )
		);
	}

	public function test_an_unreadable_directory_is_null(): void {
		if ( function_exists( 'posix_geteuid' ) && 0 === posix_geteuid() ) {
			$this->markTestSkipped( 'Running as root: a directory cannot be made unreadable.' );
		}
		$base = $this->tmp();
		$this->put( $base . '/page/index.html' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture in a temporary directory.
		chmod( $base . '/page', 0000 );

		$present = aafm_siteops_cache_files_present( $base . '/page', '/^index\.html$/' );
		$stat    = aafm_siteops_cache_files_stat( $base . '/page', '/^index\.html$/' );
		$tree    = aafm_siteops_cache_tree_has_files( $base, '/^index\.html$/' );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_chmod -- Test fixture in a temporary directory.
		chmod( $base . '/page', 0755 );

		$this->assertNull( $present );
		$this->assertNull( $stat );
		$this->assertNull( $tree );
	}

	public function test_cache_tree_has_files(): void {
		$base = $this->tmp();
		$this->assertFalse( aafm_siteops_cache_tree_has_files( $base, '/\.html$/' ) );
		$this->assertNull( aafm_siteops_cache_tree_has_files( $base . '/missing', '/\.html$/' ) );

		$this->put( $base . '/a/b/c/readme.txt' );
		$this->assertFalse( aafm_siteops_cache_tree_has_files( $base, '/\.html$/' ) );
		$this->put( $base . '/a/b/c/index.html' );
		$this->assertTrue( aafm_siteops_cache_tree_has_files( $base, '/\.html$/' ) );
	}

	public function test_a_tree_walk_stops_at_5000_entries(): void {
		$base = $this->tmp();
		wp_mkdir_p( $base . '/many' );
		for ( $i = 0; $i < 5001; $i++ ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_touch -- Test fixture in a temporary directory.
			touch( $base . '/many/' . $i . '.txt' );
		}
		$this->assertNull( aafm_siteops_cache_tree_has_files( $base, '/\.html$/' ) );
	}
}
